<?php

namespace Gini\Process\Driver;

class Engine
{
    public static function of($name) {
        return \Gini\IoC::construct('\Gini\Process\Driver\Engine\\'.$name);
    }
}
