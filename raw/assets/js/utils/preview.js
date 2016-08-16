define('utils/preview', ['jquery', 'bootstrap'], function($) {

    // Tooltip.propotype.fixPosition
    $.fn.popover.Constructor.prototype.fixPreviewPos = function() {
        var $tip = this.tip();
        var pos = this.getPosition();
        var actualWidth = $tip[0].offsetWidth;
        var actualHeight = $tip[0].offsetHeight;
        var placement = 'bottom';
        var placement = $(this.$element).data('placement') || 'right';
        var calculatedOffset = this.getCalculatedOffset(placement, pos, actualWidth, actualHeight)
        this.applyPlacement(calculatedOffset, placement)
    };

    $(function() {

        var clockPopover;
        /* Preview 处理事件 */
        $('body').popover({
            selector: '[data-preview]'
            ,animation: true
            ,placement: function(tip, ele) {
                var placement = $(ele).data('placement') || 'right';
                return placement;
            }
            ,trigger: 'hover'
            ,delay: {
                hide: 50
            }
            ,html: true
            ,content: function() {
                var preview = $(this);
                var content = preview.data('content');

                if (content) return content;

                setTimeout(function() {
                    $.ajax({
                        type: 'post'
                        ,url: preview.data('href')
                        ,success: function(data, code) {
                            var popo = preview.next('.popover')
                            ,position = popo.position()
                            ,height
                            ,top;
                            if (popo.length) {
                                height = popo.outerHeight(true);
                                popo.find('.popover-content').html(data);
                                if (preview.data('placement') === 'right' || preview.data('placement') === 'left') {
                                    top = position.top - (popo.outerHeight(true) - height) / 2;
                                    popo.css({
                                        'top': top
                                    });
                                }
                                preview.data('content', data);
                                preview.popover('fixPreviewPos');
                            }
                        }
                    });
                }, 1);

                return '<div class="loading text-center"><i class="fa fa-spinner fa-spin fa-2x"></i></div>';

            }
        }).on('show.bs.popover', function(e) {
            clockPopover && clearTimeout(clockPopover);
            var $popover = $(e.target).next('.popover');

            //让里面的元素把popover撑开
            $popover.css('max-width', 'none');
            //把不是自己的所有data-preview后面的popover删除
            $('[data-preview]').next('.popover').not($popover[0]).remove();
        }).on('hide.bs.popover', function(e) {
            if (!focusedPopover || $(e.target).next('.popover').get(0) !== focusedPopover.get(0)) return;
            e.preventDefault();
            clockPopover = setTimeout(function() {
                $(e.target).popover('hide');
            }, 100);
            return false;
        });

        var focusedPopover;
        $('body').on('mouseover', '.popover', function() {
            focusedPopover = $(this);
        }).on('mouseout', '.popover', function() {
            focusedPopover = null;
        });

    });
});

