/* global kurv_settings */
const settings = window.kurv_settings || {};
const label = window.wp.htmlEntities.decodeEntities( settings.title ) || window.wp.i18n.__( 'Kurv', 'kurv-woocommerce' );

const Content = () => {
	return window.wp.htmlEntities.decodeEntities( settings.description || '' );
};

// Render icon + title inline, or fall back to plain text label if no icon is set.
const labelContent = settings.icon
	? window.wp.element.createElement(
		'span',
		{ className: 'kurv-payment-method-label' },
		window.wp.element.createElement( 'img', {
			className: 'kurv-payment-method-icon',
			src: settings.icon,
			alt: label,
		} ),
		window.wp.element.createElement( 'span', { className: 'kurv-payment-method-title' }, label )
	)
	: label;

const Block_Gateway = {
	name: 'kurv',
	label: labelContent,
	content: Object( window.wp.element.createElement )( Content, null ),
	edit: Object( window.wp.element.createElement )( Content, null ),
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports || [ 'products' ],
	},
};

window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );
