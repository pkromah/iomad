define(['qtype_structuredsteps/widgets/plain'], function(PlainWidget) {
    function GraphPlotWidget(fieldEl) {
        PlainWidget.call(this, fieldEl);
        this.canvas = null;
        this.inputHandler = null;
    }

    GraphPlotWidget.prototype = Object.create(PlainWidget.prototype);
    GraphPlotWidget.prototype.constructor = GraphPlotWidget;

    GraphPlotWidget.prototype.init = function() {
        var field = this.fieldEl;
        var target = field.getAttribute('id');
        this.canvas = document.querySelector('.structuredsteps-graph-canvas[data-target="' + target + '"]');
        if (this.canvas) {
            this.canvas.setAttribute('aria-hidden', 'true');
        }

        this.inputHandler = function() {
            if (!field.value) {
                field.removeAttribute('data-ss-json-valid');
                return;
            }

            try {
                JSON.parse(field.value);
                field.setAttribute('data-ss-json-valid', '1');
            } catch (e) {
                field.setAttribute('data-ss-json-valid', '0');
            }
        };

        field.addEventListener('input', this.inputHandler);
    };

    GraphPlotWidget.prototype.destroy = function() {
        if (this.inputHandler) {
            this.fieldEl.removeEventListener('input', this.inputHandler);
        }
    };

    return GraphPlotWidget;
});
