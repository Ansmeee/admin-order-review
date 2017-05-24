<?php

namespace Gini\Controller\CGI\Rest;


class Order extends \Gini\Controller\CGI\Layout
{
    public function actionApprove()
    {
        error_log('pass');
        $post = $this->form('post');
        error_log(J($post));

    }

    public function actionReject()
    {
        error_log('reject');
        $post = $this->form('post');
        error_log(J($post));

    }
}
