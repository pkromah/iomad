define([
    'qtype_structuredsteps/widgets/plain',
    'qtype_structuredsteps/widgets/choice',
    'qtype_structuredsteps/widgets/digitbox',
    'qtype_structuredsteps/widgets/numberbox',
    'qtype_structuredsteps/widgets/tokenbank',
    'qtype_structuredsteps/widgets/mathfield',
    'qtype_structuredsteps/widgets/graphplot',
    'qtype_structuredsteps/widgets/unitpicker',
    'qtype_structuredsteps/ui/navigation',
    'qtype_structuredsteps/ui/inputdock',
    'qtype_structuredsteps/ui/hintpanel'
], function(
    PlainWidget,
    ChoiceWidget,
    DigitBoxWidget,
    NumberBoxWidget,
    TokenBankWidget,
    MathFieldWidget,
    GraphPlotWidget,
    UnitPickerWidget,
    Navigation,
    InputDock,
    HintPanel
) {
    var WIDGETS = {
        choice: ChoiceWidget,
        digitbox: DigitBoxWidget,
        numberbox: NumberBoxWidget,
        tokenbank: TokenBankWidget,
        mathfield: MathFieldWidget,
        graphplot: GraphPlotWidget,
        unitpicker: UnitPickerWidget
    };

    var parseConfig = function(raw) {
        if (!raw) {
            return {};
        }

        try {
            var parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (e) {
            return {};
        }
    };

    var resolveLayout = function(layoutMode) {
        if (layoutMode === 'grid' || layoutMode === 'step_card') {
            return layoutMode;
        }

        return window.matchMedia('(min-width: 768px)').matches ? 'grid' : 'step_card';
    };

    var applyLayout = function(root, config) {
        var requested = config.layoutMode ? String(config.layoutMode) : 'auto';
        var resolved = resolveLayout(requested);
        root.setAttribute('data-ss-layout', resolved);
    };

    var getFieldType = function(fieldEl) {
        var fieldType = fieldEl.getAttribute('data-ss-field-type');
        return fieldType ? String(fieldType).toLowerCase() : 'plain';
    };

    var createRuntimeApi = function(root, config) {
        return {
            root: root,
            config: config,
            widgets: [],
            ui: {}
        };
    };

    var instantiateWidgets = function(root, runtimeApi) {
        var fieldEls = root.querySelectorAll('.ss-field[data-ss-field-id]');

        fieldEls.forEach(function(fieldEl) {
            var fieldType = getFieldType(fieldEl);
            var WidgetCtor = WIDGETS[fieldType] || PlainWidget;
            var widget;

            try {
                widget = new WidgetCtor(fieldEl, runtimeApi);
                if (widget && typeof widget.init === 'function') {
                    widget.init();
                }
            } catch (e) {
                widget = new PlainWidget(fieldEl, runtimeApi);
                if (typeof widget.init === 'function') {
                    widget.init();
                }
            }

            runtimeApi.widgets.push(widget);
        });
    };

    var instantiateUiModules = function(root, runtimeApi) {
        runtimeApi.ui.navigation = new Navigation(root, runtimeApi);
        runtimeApi.ui.navigation.init();

        runtimeApi.ui.inputdock = new InputDock(root, runtimeApi.ui.navigation, runtimeApi);
        runtimeApi.ui.inputdock.init();

        runtimeApi.ui.hintpanel = new HintPanel(root, runtimeApi);
        runtimeApi.ui.hintpanel.init();

        // QSS-043: inject progress bar above first answer step card.
        injectProgressBar(root);
    };

    /**
     * QSS-043: Insert a thin progress bar above the first answer step card.
     * The bar reflects how many answer steps have a non-empty field value.
     *
     * @param {Element} root
     */
    var injectProgressBar = function(root) {
        var answerCards = Array.from(root.querySelectorAll('.ss-step-card:not(.ss-step-card--context)'));
        if (answerCards.length < 2) {
            return; // no bar needed for single-step questions
        }

        var bar = document.createElement('div');
        bar.className = 'ss-progress-bar-wrap';
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', 'Question progress');
        bar.setAttribute('aria-valuenow', '0');
        bar.setAttribute('aria-valuemax', String(answerCards.length));
        bar.setAttribute('aria-valuetext', 'Step 0 of ' + answerCards.length);

        var fill = document.createElement('div');
        fill.className = 'ss-progress-bar-fill';
        fill.style.width = '0%';
        bar.appendChild(fill);

        // Insert before the first step card (context card comes before it).
        answerCards[0].parentNode.insertBefore(bar, answerCards[0]);
        root._ssProgressBar = {wrap: bar, fill: fill, total: answerCards.length};

        // Update on any field change inside the question.
        root.addEventListener('change', function() {
            updateProgressBar(root);
        });
        root.addEventListener('input', function() {
            updateProgressBar(root);
        });
    };

    var updateProgressBar = function(root) {
        var pb = root._ssProgressBar;
        if (!pb) {
            return;
        }

        var answerCards = root.querySelectorAll('.ss-step-card:not(.ss-step-card--context)');
        var filled = 0;
        answerCards.forEach(function(card) {
            var hasValue = Array.from(card.querySelectorAll('.ss-field')).some(function(f) {
                return (f.value || '').trim() !== '';
            });
            if (hasValue) {
                filled++;
            }
        });

        var pct = pb.total > 0 ? Math.round((filled / pb.total) * 100) : 0;
        pb.fill.style.width = pct + '%';
        pb.wrap.setAttribute('aria-valuenow', String(filled));
        pb.wrap.setAttribute('aria-valuetext', 'Step ' + filled + ' of ' + pb.total);
    };

    var destroyWidgets = function(runtimeApi) {
        if (!runtimeApi || !runtimeApi.widgets) {
            return;
        }

        runtimeApi.widgets.forEach(function(widget) {
            if (widget && typeof widget.destroy === 'function') {
                widget.destroy();
            }
        });

        runtimeApi.widgets = [];
    };

    var destroyUiModules = function(runtimeApi) {
        if (!runtimeApi || !runtimeApi.ui) {
            return;
        }

        Object.keys(runtimeApi.ui).forEach(function(key) {
            var mod = runtimeApi.ui[key];
            if (mod && typeof mod.destroy === 'function') {
                mod.destroy();
            }
        });

        runtimeApi.ui = {};
    };

    var initQuestionRoot = function(root) {
        if (!root) {
            return;
        }

        if (root._ssRuntime) {
            destroyUiModules(root._ssRuntime);
            destroyWidgets(root._ssRuntime);
        }

        var config = parseConfig(root.getAttribute('data-ss-config'));
        var runtimeApi = createRuntimeApi(root, config);

        applyLayout(root, config);
        instantiateWidgets(root, runtimeApi);
        instantiateUiModules(root, runtimeApi);

        root._ssRuntime = runtimeApi;
        root.setAttribute('data-ss-runtime-init', '1');
        root.classList.add('ss-runtime-ready');
    };

    var refreshLayouts = function() {
        var roots = document.querySelectorAll('.ss-question[data-ss-runtime-init="1"]');
        roots.forEach(function(root) {
            var config = root._ssRuntime ? root._ssRuntime.config : {};
            applyLayout(root, config || {});
        });
    };

    var initAll = function() {
        var roots = document.querySelectorAll('.ss-question[data-ss-config]');
        roots.forEach(initQuestionRoot);

        if (!window.__qtypeStructuredstepsResizeBound) {
            window.addEventListener('resize', refreshLayouts, {passive: true});
            window.__qtypeStructuredstepsResizeBound = true;
        }
    };

    return {
        initAll: initAll,
        initQuestionRoot: initQuestionRoot
    };
});
