<?php
/**
 *  NIP Autofill module — AJAX lookup controller.
 *
 *  Proxies the Polish Ministry of Finance VAT register
 *  (https://wl-api.mf.gov.pl) so the storefront never hits it
 *  cross-origin, and caches conclusive answers per day to stay
 *  well within the free API limits.
 *
 *  @author    W3B Solutions <info@w3bsolutions.es>
 *  @copyright 2016-2026
 *  @license   private license
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class W3b_NipAutofillLookupModuleFrontController extends ModuleFrontController
{
    const API_URL = 'https://wl-api.mf.gov.pl/api/search/nip/';

    public function postProcess()
    {
        if (!W3b_NipAutofill::checkToken((string) Tools::getValue('token'))) {
            $this->respond(['ok' => false, 'reason' => 'token'], 403);
        }

        $nip = W3b_NipAutofill::sanitizeNip(Tools::getValue('nip'));
        if (!W3b_NipAutofill::isValidNip($nip)) {
            $this->respond(['ok' => false, 'reason' => 'invalid_nip']);
        }

        $today = date('Y-m-d');

        $cached = Db::getInstance()->getValue(
            'SELECT `payload` FROM `' . _DB_PREFIX_ . 'w3b_nipautofill_cache`
             WHERE `nip` = \'' . pSQL($nip) . '\' AND `date_checked` = \'' . pSQL($today) . '\''
        );
        if ($cached) {
            $payload = json_decode($cached, true);
            if (is_array($payload)) {
                $payload['source'] = 'cache';
                $this->respond($payload);
            }
        }

        // Cache misses are the only calls that cost us an upstream request,
        // so the throttle sits here rather than at the top of the action.
        if (!$this->consumeRateLimit()) {
            $this->respond(['ok' => false, 'reason' => 'rate_limited'], 429);
        }

        $result = $this->queryWhitelist($nip, $today);
        if ($result === null) {
            $this->respond(['ok' => false, 'reason' => 'api_error']);
        }

        // Cache only conclusive answers (found / not found), never transport errors.
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'w3b_nipautofill_cache` (`nip`, `date_checked`, `payload`, `date_add`)
             VALUES (\'' . pSQL($nip) . '\', \'' . pSQL($today) . '\', \'' . pSQL(json_encode($result)) . '\', NOW())'
        );
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'w3b_nipautofill_cache` WHERE `date_add` < DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );

        $result['source'] = 'api';
        $this->respond($result);
    }

    /**
     * Sliding hourly window per IP. Returns false once the caller is over
     * the limit, so a scraped page token cannot be replayed indefinitely.
     */
    private function consumeRateLimit()
    {
        $table = _DB_PREFIX_ . 'w3b_nipautofill_rate';
        $ipHash = md5(Tools::getRemoteAddr());
        $db = Db::getInstance();

        $done = $db->execute(
            'INSERT INTO `' . $table . '` (`ip_hash`, `window_start`, `hits`)
             VALUES (\'' . pSQL($ipHash) . '\', NOW(), 1)
             ON DUPLICATE KEY UPDATE
                `hits` = IF(`window_start` < DATE_SUB(NOW(), INTERVAL 1 HOUR), 1, `hits` + 1),
                `window_start` = IF(`window_start` < DATE_SUB(NOW(), INTERVAL 1 HOUR), NOW(), `window_start`)'
        );
        // Older installs upgraded by copying files only: create on first use.
        if (!$done) {
            W3b_NipAutofill::createRateTable();

            return true;
        }

        $db->execute(
            'DELETE FROM `' . $table . '` WHERE `window_start` < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );

        $hits = (int) $db->getValue(
            'SELECT `hits` FROM `' . $table . '` WHERE `ip_hash` = \'' . pSQL($ipHash) . '\''
        );

        return $hits <= W3b_NipAutofill::RATE_LIMIT_PER_HOUR;
    }

    /**
     * @return array|null Conclusive payload, or null on transport/API failure.
     */
    private function queryWhitelist($nip, $date)
    {
        $ch = curl_init(self::API_URL . $nip . '?date=' . $date);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'PrestaShop-w3b_nipautofill/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return null;
        }

        $json = json_decode($body, true);

        // The API answers 400 with a WL-* code for NIPs absent from the register.
        if ($status === 400 && isset($json['code'])) {
            return ['ok' => true, 'found' => false];
        }
        if ($status !== 200 || !is_array($json)) {
            return null;
        }

        $subject = isset($json['result']['subject']) ? $json['result']['subject'] : null;
        if (!$subject || empty($subject['name'])) {
            return ['ok' => true, 'found' => false];
        }

        $address = '';
        if (!empty($subject['workingAddress'])) {
            $address = $subject['workingAddress'];
        } elseif (!empty($subject['residenceAddress'])) {
            $address = $subject['residenceAddress'];
        }
        list($street, $postcode, $city) = $this->parseAddress($address);

        return [
            'ok' => true,
            'found' => true,
            'data' => [
                'nip' => $nip,
                'company' => (string) $subject['name'],
                'address1' => $street,
                'postcode' => $postcode,
                'city' => $city,
                'statusVat' => isset($subject['statusVat']) ? (string) $subject['statusVat'] : '',
            ],
        ];
    }

    /**
     * Register addresses come as "UL. PRZYKŁADOWA 1/2, 00-001 WARSZAWA".
     *
     * @return array{0: string, 1: string, 2: string} street, postcode, city
     */
    private function parseAddress($address)
    {
        $address = trim((string) $address);
        $street = '';
        $postcode = '';
        $city = '';

        $comma = mb_strrpos($address, ',');
        if ($comma !== false) {
            $street = trim(mb_substr($address, 0, $comma));
            $rest = trim(mb_substr($address, $comma + 1));
        } else {
            $rest = $address;
        }

        if (preg_match('/^(\d{2}-\d{3})\s+(.+)$/u', $rest, $m)) {
            $postcode = $m[1];
            $city = $m[2];
        } else {
            $city = $rest;
        }

        return [self::titleCase($street), $postcode, self::titleCase($city)];
    }

    private static function titleCase($value)
    {
        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    private function respond(array $payload, $httpCode = 200)
    {
        if ($httpCode !== 200) {
            http_response_code($httpCode);
        }
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode($payload));
    }
}
