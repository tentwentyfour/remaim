<?php

$struct = [
    [
        'id' => 1,
        'name' =>  'parent',
    ],
    [
        'id' => 2,
        'name' => 'child',
        'parent' => [
            'id' => 1,
            'name' => 'parent'
        ]
    ],
    [
        'id' => 3,
        'name' => 'grandchild',
        'parent' => [
            'id' => 2,
            'name' => 'child'
        ]
    ]
];

$container = [];

function mapThatShit($container, $item) {
    return array_map(function ($slot) use ($item) {
        // var_dump('is', $item['parent'], $slot);
        // If the parent id matches the slot id, we're a direct child
        // and can be added to this parent
        if ($item['parent']['id'] === $slot['id']) {
            $slot['children'][] = $item;
        } else {
            // if not, then we're a child's child and… it's even possible that our parent doesn't have a slot yet…
        }
        return $slot;
    }, $container);

    // var_dump($struct, $container, $container2);
}

array_walk($struct, function ($item, $key) use (&$container) {
    // var_dump('before', $container);
    if (isset($item['parent'])) {
        $container = mapThatShit($container, $item);
    } else {
        $container[] = $item;
    }
    // var_dump('after', $container);
}, []);

var_dump($container);
