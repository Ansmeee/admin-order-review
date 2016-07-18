<?php

namespace Gini\Controller\CGI;

class Index extends Layout\Board{

    public function __index(){
        $this->redirect('review');
    }

    public function actionLogout(){
        \Gini\Gapper\Client::logout();
        $this->redirect('/');
    }
}
