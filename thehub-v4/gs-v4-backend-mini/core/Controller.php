<?php
// core/Controller.php

abstract class Controller
{
    protected function render(string $viewPath, array $data = [], string $layout = 'layout'): void
    {
        extract($data);
        $layoutFile = __DIR__ . '/../public/templates/' . $layout . '.php';

        if (!file_exists($viewPath)) {
            throw new RuntimeException('View not found: ' . $viewPath);
        }
        if (!file_exists($layoutFile)) {
            throw new RuntimeException('Layout not found: ' . $layoutFile);
        }

        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        require $layoutFile;
    }
}
