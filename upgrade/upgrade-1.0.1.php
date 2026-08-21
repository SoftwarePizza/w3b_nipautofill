<?php
/**
 *  1.0.0 -> 1.0.1
 *
 *  Adds the per-IP throttle table. The lookup token stopped being derived
 *  from the customer session in this version, so the throttle is what now
 *  limits how often the proxy may reach the MF API.
 *
 *  @author    W3B Solutions <info@w3bsolutions.es>
 *  @copyright 2016-2026
 *  @license   private license
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    return W3b_NipAutofill::createRateTable();
}
