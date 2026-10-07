(function (wp) {
    var el = wp.element.createElement;
    var registerBlockType = wp.blocks.registerBlockType;
    var TextControl = wp.components.TextControl;

    registerBlockType('civ/voting', {
        title: 'Community idea voting',
        description: 'Embed an anonymous pairwise idea-voting survey.',
        icon: 'format-chat',
        category: 'widgets',
        attributes: {
            surveyId: { type: 'number', default: 0 }
        },
        edit: function (props) {
            return el(
                'div',
                { className: 'components-placeholder' },
                el('div', { className: 'components-placeholder__label' }, 'Community idea voting'),
                el(TextControl, {
                    label: 'Survey ID',
                    type: 'number',
                    min: 1,
                    value: props.attributes.surveyId || '',
                    onChange: function (value) {
                        props.setAttributes({ surveyId: parseInt(value, 10) || 0 });
                    }
                }),
                el('p', null, 'The voting experience will appear on the published page.')
            );
        },
        save: function () {
            return null;
        }
    });
}(window.wp));
