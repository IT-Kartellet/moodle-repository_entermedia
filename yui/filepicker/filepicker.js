YUI.add('moodle-repository_entermedia-filepicker', function(Y) {
    // Fix for https://tracker.moodle.org/browse/MDL-35897 / https://tracker.moodle.org/browse/MDL-35274 - search page should not be cacheable
    var originalInit = M.core_filepicker.init;

    M.core_filepicker.init = function (Y, options) {
        originalInit.apply(this, arguments);

        var instance = M.core_filepicker.instances[options.client_id],
            originalRequest = instance.request;

        instance.request = function (args) {
            if (args.action == 'searchform') {
                // Remove all cached responses
                this.cached_responses = {};

                var originalCallback = args.callback;
                args.callback = function () {
                    originalCallback.apply(null, arguments);

                    Y.one('.fp-tb-search form').addClass('mform');

                    jQuery('.search_more select').SumoSelect({
                        csvDispCount: 2,
                        placeholder: M.util.get_string('search_placeholder', 'repository_entermedia'),
                        selectAlltext: M.util.get_string('search_selectall', 'repository_entermedia')
                    });
                }
            }
            originalRequest.apply(this, arguments);

        };
    };

    M.repository_entermedia = M.repository_entermedia || {};
    M.repository_entermedia.filepicker = {
        init: function() {}
    };
}, '@VERSION@', {
    requires: ['core_filepicker', 'transition', 'anim']
});