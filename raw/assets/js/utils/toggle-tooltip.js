define('utils/toggle-tooltip', ['jquery', 'bootstrap'], function($) {
    $(document).on('mouseover', '[data-toggle="tooltip"]', function () {
        $(this).tooltip('show');
    });
});

