<?php
/** Admin-only entry point for the shared notification inbox. */
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__ . '/includes/navigation.php';
need_role('admin');
require __DIR__.'/../notifications/index.php';
