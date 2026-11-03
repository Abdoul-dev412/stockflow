<?php

test('toutes les routes nommées pointent vers des classes et méthodes existantes', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->getName() !== null);

    expect($routes)->not->toBeEmpty();

    $routes->each(function ($route) {
        $action = $route->getActionName();

        // Ignore les closures et les vues directes
        if ($action === 'Closure' || str_starts_with($action, 'view:')) {
            return;
        }

        // Contrôleur invocable : App\Http\Controllers\DashboardController
        if (! str_contains($action, '@')) {
            expect(class_exists($action))
                ->toBeTrue("Route '{$route->getName()}' pointe vers une classe inexistante : {$action}");

            expect(method_exists($action, '__invoke'))
                ->toBeTrue("Route '{$route->getName()}' pointe vers un contrôleur sans méthode __invoke : {$action}");

            return;
        }

        // Contrôleur classique : Controller@method
        [$class, $method] = explode('@', $action);

        expect(class_exists($class))
            ->toBeTrue("Route '{$route->getName()}' pointe vers une classe inexistante : {$class}");

        expect(method_exists($class, $method))
            ->toBeTrue("Route '{$route->getName()}' pointe vers une méthode inexistante : {$class}@{$method}");
    });
});