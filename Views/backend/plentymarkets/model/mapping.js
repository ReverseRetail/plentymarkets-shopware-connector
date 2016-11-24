// {namespace name=backend/Plentymarkets/model}
// {block name=backend/Plentymarkets/model/Settings}

/**
 * The settings data model defines the different data fields for reading,
 * saving, deleting settings data and is extended by the Ext data model
 * "Ext.data.Model".
 *
 * @author Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */
Ext.define('Shopware.apps.Plentymarkets.model.Mapping', {

    extend: 'Ext.data.Model',

    fields: [
        // {block name="backend/Plentymarkets/model/Settings/fields"}{/block}
        {
            name: 'originAdapterName',
            type: 'string'
        },
        {
            name: 'originTransferObjects',
            type: 'auto'
        },
        {
            name: 'destinationAdapterName',
            type: 'string'
        },
        {
            name: 'destinationTransferObjects',
            type: 'auto'
        },
        {
            name: 'isComplete',
            type: 'boolean'
        }
    ],

    proxy: {
        type: 'ajax',

        reader: {
            type: 'json',
            root: 'data'
        }
    }

});
// {/block}
