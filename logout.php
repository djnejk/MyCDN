<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use MyCDN\Auth;

(new Auth($config))->logout();
redirect('index.php');
