<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 

function callback_remove() {
	global $m;
	$m->query("DROP TABLE IF EXISTS `".DB_PREFIX."mok_bduss`");
}