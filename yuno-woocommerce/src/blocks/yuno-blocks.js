import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { createElement, useEffect } from '@wordpress/element';

const settings = getSetting('yuno_card_data', {});
const title    = decodeEntities(settings.title || 'Yuno');

const Label = () => createElement(
    'span',
    { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' } },
    title,
    settings.icon
        ? createElement('img', {
            src: settings.icon,
            alt: '',
            style: { height: '20px', width: 'auto' },
        })
        : null
);

const CardIcons = () => {
    const cardIcons = settings.cardIcons || [];
    if (cardIcons.length === 0) return null;

    return createElement(
        'div',
        { style: { display: 'flex', gap: '8px', marginTop: '8px' } },
        ...cardIcons.map((icon) =>
            createElement('img', {
                key: icon.name,
                src: icon.src,
                alt: icon.name,
                style: { height: '24px', width: 'auto' },
            })
        )
    );
};

const Content = (props) => {
    const { eventRegistration, emitResponse } = props;
    const { onPaymentSetup } = eventRegistration;

    useEffect(() => {
        const unsubscribe = onPaymentSetup(() => ({
            type: emitResponse.responseTypes.SUCCESS,
            meta: { paymentMethodData: {} },
        }));
        return unsubscribe;
    }, [onPaymentSetup, emitResponse.responseTypes.SUCCESS]);

    return createElement('div', null,
        createElement('p', null, decodeEntities(settings.description || '')),
        createElement(CardIcons)
    );
};

const Edit = () => createElement('div', null,
    createElement('p', null, decodeEntities(settings.description || '')),
    createElement(CardIcons)
);

registerPaymentMethod({
    name: 'yuno_card',
    label: createElement(Label),
    content: createElement(Content),
    edit: createElement(Edit),
    canMakePayment: () => true,
    ariaLabel: title,
    supports: {
        features: settings.supports || ['products'],
    },
});
