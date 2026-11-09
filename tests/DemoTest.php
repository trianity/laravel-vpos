<?php

test('boots Testbench in the testing environment', function () {
    expect(app()->environment())->toBe('testing')
        ->and(app()->runningInConsole())->toBeTrue();
});
