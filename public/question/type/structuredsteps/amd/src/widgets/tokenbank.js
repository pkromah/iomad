define(['qtype_structuredsteps/widgets/plain'], function(PlainWidget) {
    function TokenBankWidget(fieldEl) {
        PlainWidget.call(this, fieldEl);
        this.blurHandler = null;
    }

    TokenBankWidget.prototype = Object.create(PlainWidget.prototype);
    TokenBankWidget.prototype.constructor = TokenBankWidget;

    TokenBankWidget.prototype.init = function() {
        var field = this.fieldEl;

        this.blurHandler = function() {
            field.value = (field.value || '').replace(/\s+/g, ' ').trim();
        };

        field.addEventListener('blur', this.blurHandler);
    };

    TokenBankWidget.prototype.destroy = function() {
        if (this.blurHandler) {
            this.fieldEl.removeEventListener('blur', this.blurHandler);
        }
    };

    return TokenBankWidget;
});
