function init() {
	if (typeof riskCubeCf === 'undefined') {
		console.error('riskCubeCf object not present')
	}

	require('riskcube.legacy.js');
	if (typeof wc === 'object') {
		require('riskcube.blocks.js');
	}
}

jQuery(() => init());