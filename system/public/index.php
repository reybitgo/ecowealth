<?php
declare(strict_types=1);

/* Entry point — send visitors where they belong. */
require __DIR__ . '/../app/bootstrap.php';

if (auth_check()) {
    redirect('dashboard');
}

redirect('login');
