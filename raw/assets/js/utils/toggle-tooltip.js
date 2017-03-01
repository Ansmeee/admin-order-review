define('utils/toggle-tooltip', ['jquery', 'bootstrap'], function($) {
    $(document).on('mousever', '[data-toggle="tooltip"]', function () {
        $(this).tooltip('show');
    });
});

