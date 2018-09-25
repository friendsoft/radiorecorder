<?php

use function DI\autowire;
use function DI\get;
use function DI\env;
use Friendsoft\Radiorecorder\Radiorecorder;

require_once __DIR__ . '/../vendor/autoload.php';

$definitions = [
    /* options */
    'target' => env('RADIORECORDER_TARGET_DIR', sys_get_temp_dir()),

    /* services */
    Radiorecorder::class => autowire()->constructorParameter('target', get('target')),
];

$builder = new \DI\ContainerBuilder();
$builder->addDefinitions($definitions);
$container = $builder->build();

return $container;
