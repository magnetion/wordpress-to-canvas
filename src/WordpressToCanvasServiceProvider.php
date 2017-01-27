<?php

namespace Magnetion\WordpressToCanvas;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class WordpressToCanvasServiceProvider extends ServiceProvider
{

    protected $defer = false;

    protected $commands = [
        'Magnetion\WordpressToCanvas\Console\Commands\ImportWordPress'
    ];

    public function boot()
    {
        $app = $this->app;
    }


    public function register()
    {
        $this->commands($this->commands);
    }

}