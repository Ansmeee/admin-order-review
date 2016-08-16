<?php

namespace Gini\Controller\CGI;

class Index extends Layout\Board{

    public function __index(){
        $this->redirect('review');
    }

    public function actionLogout()
    {
        \Gini\Gapper\Client::logout();
        $this->redirect('/');
    }

    public function actionPending()
    {
        $vars = [
            'type'=> 'pending'
        ];

        $this->view->body = V('review/index', $vars);
    }

    public function actionHistory()
    {
        $vars = [
            'type'=> 'history'
        ];

        $this->view->body = V('review/index', $vars);
    }

}
