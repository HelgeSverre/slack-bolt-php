<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$errors = [];
foreach ($checks as $index => $check) {
    try {
        $class = new ReflectionClass($check['class']);
        if ($check['kind'] === 'construct') {
            if (!$class->isInstantiable())
                $errors[] = ['index' => $index, 'message' => $check['class'] . ' cannot be constructed publicly.'];
            continue;
        }
        if (!$class->hasMethod($check['method'])) {
            $errors[] = [
                'index' => $index,
                'message' => 'Unknown static API: ' . $check['class'] . '::' . $check['method'],
            ];
            continue;
        }
        $method = $class->getMethod($check['method']);
        if (!$method->isStatic() || !$method->isPublic())
            $errors[] = [
                'index' => $index,
                'message' => 'Not a public static API: ' . $check['class'] . '::' . $check['method'],
            ];
    } catch (Throwable) {
        $errors[] = ['index' => $index, 'message' => 'Cannot reflect documented API: ' . $check['class']];
    }
}
fwrite(STDOUT, json_encode($errors, JSON_THROW_ON_ERROR));
