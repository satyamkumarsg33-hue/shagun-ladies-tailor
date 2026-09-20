<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

clear_authenticated_user();

header('Location: index.php');
exit;
