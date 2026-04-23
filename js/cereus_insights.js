/*
 * Cereus Insights — UI JavaScript
 * Loaded via page_head hook (persists across AJAX navigations).
 * Uses MutationObserver to detect plugin pages after AJAX nav.
 */

(function($) {
    'use strict';

    /* -----------------------------------------------------------------------
     * Expand / collapse detail rows on Summaries page
     * -------------------------------------------------------------------- */
    function bindSummaryExpand() {
        $(document).off('click.cisExpand', '.cis-expand-btn')
            .on('click.cisExpand', '.cis-expand-btn', function() {
                var id      = $(this).data('id');
                var $detail = $('#cis-detail-' + id);

                if ($detail.is(':visible')) {
                    $detail.hide();
                    $(this).text($(this).text().replace(/Collapse/i, 'Expand'));
                } else {
                    $detail.show();
                    $(this).text($(this).text().replace(/Expand/i, 'Collapse'));
                }
            });
    }

    /* -----------------------------------------------------------------------
     * Color forecast "Days Remaining" cells dynamically
     * (supplements inline PHP coloring as a safety net)
     * -------------------------------------------------------------------- */
    function colorForecastDays() {
        $('td').filter(function() {
            var text = $(this).text().trim();
            return /^\d+\s+days?$/.test(text);
        }).each(function() {
            var days = parseInt($(this).text(), 10);
            if (isNaN(days)) { return; }

            if (days < 7) {
                $(this).css({ color: '#d9534f', fontWeight: 'bold' });
                $(this).closest('tr').css('background-color', '#fdecea');
            } else if (days < 30) {
                $(this).css({ color: '#f0ad4e', fontWeight: 'bold' });
                $(this).closest('tr').css('background-color', '#fef8e7');
            } else if (days < 90) {
                $(this).css({ color: '#c9aa06', fontWeight: 'bold' });
                $(this).closest('tr').css('background-color', '#fefde5');
            } else {
                $(this).css({ color: '#5cb85c', fontWeight: 'bold' });
            }
        });
    }

    /* -----------------------------------------------------------------------
     * Tab bar active state management
     * Highlights the correct tab based on current page URL.
     * -------------------------------------------------------------------- */
    function highlightActiveTab() {
        var path = window.location.pathname;
        $('#cereus-insights-tabs a').each(function() {
            var href = $(this).attr('href') || '';
            var file = href.split('/').pop().split('?')[0];
            var curr = path.split('/').pop().split('?')[0];

            if (file && curr && file === curr) {
                $(this).closest('li').addClass('selected');
            } else {
                $(this).closest('li').removeClass('selected');
            }
        });
    }

    /* -----------------------------------------------------------------------
     * Init: run on page load and after AJAX navigation
     * -------------------------------------------------------------------- */
    function init() {
        if ($('#cereus-insights-tabs').length) {
            highlightActiveTab();
        }

        if ($('.cis-expand-btn').length) {
            bindSummaryExpand();
        }

        if ($('td').filter(function() { return /days?/.test($(this).text()); }).length) {
            colorForecastDays();
        }
    }

    /* Run on initial DOMContentLoaded */
    $(document).ready(function() {
        init();
    });

    /* MutationObserver: re-run after Cacti AJAX navigation replaces page content */
    var _observer = null;
    function startObserver() {
        var target = document.getElementById('main_header') || document.body;
        if (!target) { return; }

        _observer = new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length) {
                    init();
                    break;
                }
            }
        });

        _observer.observe(target, { childList: true, subtree: true });
    }

    $(document).ready(function() {
        startObserver();
    });

})(jQuery);
