<?php
/**
 * Settings metadata for the publictunnel plugin
 */

$meta['binary']        = array('string');
$meta['port']          = array('numeric', '_pattern' => '/^(\d{1,5})?$/');
$meta['verify_binary'] = array('onoff');
$meta['readonly']      = array('onoff');
$meta['block_admin']   = array('onoff');
$meta['otp_ttl']       = array('numeric', '_min' => 1, '_max' => 168);
