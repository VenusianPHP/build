<?php

/*
 * Run by PharBuilder in a child PHP: php seed.php <stage>, with DB_CONNECTION and DB_DATABASE
 * naming the stage's fresh database/database.sqlite. Boots the staged app the way rocket does and
 * runs its migrations, so a packaged app's first run copies an empty, migrated database out of
 * the phar instead of the developer's. Prints how many migrations ran.
 */

$stage = rtrim((string) ($argv[1] ?? ''), '/');

require $stage.'/vendor/autoload.php';
$app = require $stage.'/bootstrap/app.php';
$app->make('Voyager\Contracts\Sketches\Kernel')->bootstrap();

$migrator = $app->make('migrator');
if (! $migrator->repositoryExists()) {
    $migrator->getRepository()->createRepository();
}

echo count($migrator->run([$stage.'/database/migrations'])), "\n";
