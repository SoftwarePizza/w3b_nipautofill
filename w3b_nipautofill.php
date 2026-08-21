<?php
/**
 *  NIP Autofill module
 *
 *  Fills in company name and address on the checkout address form
 *  from the Polish Ministry of Finance VAT register (wl-api.mf.gov.pl)
 *  after the customer types a NIP number.
 *
 *  Works with the ST Easy Checkout one page checkout (person/company
 *  radio switch) and with the standard PrestaShop checkout / address forms.
 *
 *  @author    W3B Solutions <info@w3bsolutions.es>
 *  @copyright 2016-2026
 *  @license   private license
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class W3b_NipAutofill extends Module
{
    /** Lookups a single IP may push through to the MF API per hour. */
    const RATE_LIMIT_PER_HOUR = 60;

    public function __construct()
    {
        $this->name = 'w3b_nipautofill';
        $this->tab = 'checkout';
        $this->version = '1.0.1';
        $this->author = 'W3B Solutions';
        $this->need_instance = 0;
        $this->controllers = ['lookup'];
        $this->ps_versions_compliancy = ['min' => '1.7.6', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('NIP autofill (VAT whitelist)');
        $this->description = $this->l('Fills in company name and address at checkout from the Polish VAT register after typing a NIP number.');
    }

    public function install()
    {
        return parent::install()
            && $this->createCacheTable()
            && self::createRateTable()
            && $this->registerHook('actionFrontControllerSetMedia');
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'w3b_nipautofill_cache`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'w3b_nipautofill_rate`');

        return parent::uninstall();
    }

    private function createCacheTable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'w3b_nipautofill_cache` (
                `id_cache` int unsigned NOT NULL AUTO_INCREMENT,
                `nip` char(10) NOT NULL,
                `date_checked` date NOT NULL,
                `payload` text NOT NULL,
                `date_add` datetime NOT NULL,
                PRIMARY KEY (`id_cache`),
                UNIQUE KEY `nip_date` (`nip`, `date_checked`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Per-IP throttle for calls that actually reach the MF API.
     * The page token is no longer session-bound (see makeToken), so this
     * is what keeps the proxy from being used as a free lookup service.
     */
    public static function createRateTable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'w3b_nipautofill_rate` (
                `ip_hash` char(32) NOT NULL,
                `window_start` datetime NOT NULL,
                `hits` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`ip_hash`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public function hookActionFrontControllerSetMedia()
    {
        if (!$this->isCheckoutPage()) {
            return;
        }

        $controller = $this->context->controller;

        $controller->registerJavascript(
            'module-w3b_nipautofill-front',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 150]
        );
        $controller->registerStylesheet(
            'module-w3b_nipautofill-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );

        Media::addJsDef([
            'w3bNipAutofill' => [
                'ajaxUrl' => $this->context->link->getModuleLink($this->name, 'lookup', [], true),
                'token' => self::makeToken(),
                'i18n' => [
                    'hint' => $this->l('Enter a NIP number to fetch company data automatically.'),
                    'loading' => $this->l('Fetching company data from the VAT register...'),
                    'filled' => $this->l('Company data filled in. Please verify the address.'),
                    'notFound' => $this->l('No company found for this NIP.'),
                    'invalid' => $this->l('This NIP number looks invalid.'),
                    'error' => $this->l('Could not fetch company data. Please fill in the address manually.'),
                    'rateLimited' => $this->l('Too many lookups from this connection. Please fill in the address manually.'),
                ],
            ],
        ]);
    }

    /**
     * Token for the lookup endpoint.
     *
     * Deliberately NOT Tools::getToken(), which hashes the customer id and
     * password: one page checkout logs the customer in over AJAX without a
     * reload, so a page rendered for a guest would keep sending the guest
     * token while the server already computed the logged-in one — every
     * lookup then failed with HTTP 403. This token depends only on the shop
     * and the day, so it survives login, logout and guest conversion.
     *
     * @param string|null $day Y-m-d, defaults to today
     */
    public static function makeToken($day = null)
    {
        $idShop = 0;
        $context = Context::getContext();
        if ($context && isset($context->shop) && $context->shop instanceof Shop) {
            $idShop = (int) $context->shop->id;
        }

        return Tools::hash('w3b_nipautofill|' . $idShop . '|' . ($day ?: date('Y-m-d')));
    }

    /**
     * Yesterday's token stays valid so a checkout left open across midnight
     * does not start failing mid-form.
     */
    public static function checkToken($token)
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $day) {
            if (hash_equals(self::makeToken($day), $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Standard checkout / address pages plus the ST Easy Checkout
     * one page controller (module front controller, empty php_self).
     */
    private function isCheckoutPage()
    {
        $controller = $this->context->controller;
        if (!$controller) {
            return false;
        }
        if (!empty($controller->php_self) && in_array($controller->php_self, ['order', 'address'], true)) {
            return true;
        }

        return $controller instanceof ModuleFrontController
            && isset($controller->module)
            && $controller->module instanceof Module
            && $controller->module->name === 'steasycheckout';
    }

    /**
     * Strips whitespace, dots, dashes and the PL prefix, leaving digits only.
     */
    public static function sanitizeNip($raw)
    {
        $nip = strtoupper((string) $raw);
        $nip = preg_replace('/[^0-9A-Z]/', '', $nip);
        if (strpos($nip, 'PL') === 0) {
            $nip = substr($nip, 2);
        }

        return preg_replace('/\D/', '', $nip);
    }

    public static function isValidNip($nip)
    {
        if (!preg_match('/^\d{10}$/', (string) $nip) || $nip === '0000000000') {
            return false;
        }
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += $weight * (int) $nip[$i];
        }

        return ($sum % 11) === (int) $nip[9];
    }
}
