<?php
/**
 * Settings metadata for the publictunnel plugin
 */

$meta['binary'] = array('string');
$meta['port']   = array('numeric', '_pattern' => '/^(\d{1,5})?$/');
