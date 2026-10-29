<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';

logout_session();
redirect(HALAMAN_LOGIN . '?logout=1');
