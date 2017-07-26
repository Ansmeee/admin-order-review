define('page/review', ['jquery', 'utils/bootbox', 'board', 'utils/preview'], function(jQuery, Bootbox, Board) {
    $(document).on('click', '.app-op-per-handler', function() {
        var url = 'ajax/review/get-op-form';
        var key = $(this).attr('data-key');
        var id = $(this).attr('data-id');
        $.get(url, {
            key: key
            ,id: id
        }, function(modal) {
            $(modal).modal('show');
        });
    });
    $(document).on('click', '.app-op-submit-handler', function() {
        var $modal = $(this).parents('.modal');
        var $form = $modal.find('form');
        var action = $form.attr('action');
        $.post(action, $form.serialize(), function(response) {
            response = response || {};
            var code = response.code;
            var message = response.message;
            var id = response.id;
            if (code) {
                Bootbox.alert(response.message);
                return;
            }
            var $oph = $(['[data-id=', id, ']'].join(''));
            $oph.hide();
            $modal.modal('hide');
        });
    });
    $(document).on('click', '.app-q-search-handler', function() {
        var $form = $(this).parents('form');
        var q = $form.find('[name=q]').val();
        var action = $form.attr('action');
        search({
            url: action
            ,q: q
        });
        return false;
    });
    $(document).on('click', '.app-pager-li-handler', function() {
        var page = $(this).attr('data-page');
        var type = $(this).attr('data-type');
        var group = $(this).attr('data-group');
        var $searchHandler = $('.app-q-search-handler');
        var q = '';
        if ($searchHandler.length) {
            q = $searchHandler.parents('form').find('[name=q]').val();
        }
        var url = ['ajax/review/more', page, type, group].join('/');
        search({
            url: url
            ,q: q
        });
    });

    function search(params) {
        $.get(params.url, params, function(html) {
            $('.board-content').html(html);
            Board.resize();
        });
    }

    $(document).on('click', 'a.app-link-dialog-handler', function() {
        var url = $(this).attr('href');
        $.get(url, {
            _t: (new Date()).getTime()
        }, function(modal) {
            $(modal).modal('show');
        });
        return false;
    });

    $(document).on('change', '#checkall', function() {
        var checkboxes = $("input[name='check']");
        if($(this).prop('checked')) {
            checkboxes.prop('checked', true);
        } else {
            checkboxes.prop('checked', false);
        }
    });

    $(document).on('click', '.app-op-batch-handler', function() {
        var ids = new Array();
        var checkedboxs = $("input[name='check']:checked");
        var key = $(this).data('key');
        checkedboxs.each(function() {
            var id = $(this).attr('value');
            ids.push(id);
        });

        $.get('ajax/review/get-batch-op-form', {ids:ids.toString(), key:key}, function(data){
            if(data) {
                $(data).modal('show');
            }
        });
    });

    $(document).on('click', '.app-op-batch-submit-handler', function() {
        var $modal = $(this).parents('.modal');
        var $form = $modal.find('form');
        var action = $form.attr('action');
        $.post(action, $form.serialize(), function(response) {
            response = response || {};
            var code = response.code;
            var message = response.message;
            var ids = response.ids;
            if (code) {
                Bootbox.alert(response.message);
                return;
            }

            for (var i = 0; i < ids.length; i++) {
                var id = ids[i];
                $(['[data-id=', id, ']'].join('')).hide();
            }
            $modal.modal('hide');
            location.reload();
        });
    });
});
