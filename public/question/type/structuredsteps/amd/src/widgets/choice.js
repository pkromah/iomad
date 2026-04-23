define([
    'local_pclplus_core/vendor/tom-select',
    'qtype_structuredsteps/widgets/plain',
    'core_filters/events'
], function(TomSelect, PlainWidget, FilterEvents) {

    function ChoiceWidget(fieldEl, runtimeApi) {
        PlainWidget.call(this, fieldEl);
        this.runtimeApi = runtimeApi;
        this.ts = null;
    }

    ChoiceWidget.prototype = Object.create(PlainWidget.prototype);
    ChoiceWidget.prototype.constructor = ChoiceWidget;

    ChoiceWidget.prototype.init = function() {
        var select = this.fieldEl;
        if (select.tagName.toLowerCase() !== 'select') {
            return;
        }

        // Read options including data-html for rich content (LaTeX, images).
        var options = Array.from(select.options).map(function(opt) {
            return {
                value: opt.value,
                text: opt.text,
                html: opt.getAttribute('data-html') || opt.text
            };
        });

        var selectedValue = select.value;
        var isDisabled = select.disabled;

        this.ts = new TomSelect(select, {
            options: options,
            items: selectedValue ? [selectedValue] : [],
            valueField: 'value',
            labelField: 'text',
            searchField: ['text'],
            create: false,
            controlInput: null,
            render: {
                option: function(data) {
                    return '<div class="option ss-ts-option">' + (data.html || data.text) + '</div>';
                },
                item: function(data) {
                    return '<div class="item ss-ts-item">' + (data.html || data.text) + '</div>';
                }
            },
            onInitialize: function() {
                notifyMath(this.control);
            },
            onDropdownOpen: function(dropdown) {
                notifyMath(dropdown);
            },
            onItemAdd: function() {
                notifyMath(this.control);
            }
        });

        if (isDisabled) {
            this.ts.lock();
        }

        // QSS-039: propagate step-level label to Tom Select wrapper so screen readers
        // announce the step instruction rather than the raw field id (e.g. "f1").
        var ssLabel = select.getAttribute('data-ss-label');
        if (ssLabel && this.ts.wrapper) {
            this.ts.wrapper.setAttribute('aria-label', ssLabel);
        }
    };

    ChoiceWidget.prototype.getValue = function() {
        return this.fieldEl.value;
    };

    ChoiceWidget.prototype.setValue = function(val) {
        if (this.ts) {
            this.ts.setValue((val === null || val === undefined) ? '' : String(val));
        } else {
            this.fieldEl.value = (val === null || val === undefined) ? '' : String(val);
        }
    };

    ChoiceWidget.prototype.destroy = function() {
        if (this.ts) {
            this.ts.destroy();
            this.ts = null;
        }
    };

    /**
     * Notify the Moodle filter system that new content with math has been added.
     * The mathjaxloader filter listens for this event and typesets any
     * .filter_mathjaxloader_equation elements found within the given node.
     *
     * @param {Element} el
     */
    function notifyMath(el) {
        if (el) {
            FilterEvents.notifyFilterContentUpdated([el]);
        }
    }

    return ChoiceWidget;
});
