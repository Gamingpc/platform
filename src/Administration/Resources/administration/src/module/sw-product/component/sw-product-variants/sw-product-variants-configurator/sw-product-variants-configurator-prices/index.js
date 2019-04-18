import { Component, State } from 'src/core/shopware';
import Criteria from 'src/core/data-new/criteria.data';
import template from './sw-product-variants-configurator-prices.html.twig';
import './sw-product-variants-configurator-prices.scss';

Component.register('sw-product-variants-configurator-prices', {
    template,

    inject: ['repositoryFactory', 'context'],

    props: {
        product: {
            type: Object,
            required: true
        },

        selectedGroups: {
            type: Array,
            required: true
        }
    },

    data() {
        return {
            activeGroup: {},
            term: '',
            optionsForGroup: [],
            currencies: {},
            isLoading: true
        };
    },

    mounted() {
        this.mountedComponent();
    },

    watch: {
        'activeGroup'() {
            this.getOptionsForGroup();
        },
        'term'() {
            this.getOptionsForGroup();
        }
    },

    computed: {
        currencyStore() {
            return State.getStore('currency');
        },

        currencyRepository() {
            return this.repositoryFactory.create('currency');
        },

        currenciesList() {
            return Object.values(this.currencies).map((currency) => {
                return {
                    id: currency.id,
                    name: currency.name,
                    symbol: currency.symbol
                };
            });
        },

        optionColumns() {
            const defaultColumns = [
                {
                    property: 'name',
                    label: this.$tc('sw-product.variations.configuratorModal.priceOptions'),
                    rawData: true
                }
            ];

            const currenciesColumns = this.currenciesList.map((currency) => {
                return {
                    property: `currency.${currency.id}`,
                    label: currency.name,
                    rawData: true,
                    allowResize: true,
                    width: '200px'
                };
            });

            return [...defaultColumns, ...currenciesColumns];
        }
    },

    methods: {
        mountedComponent() {
            this.isLoading = false;
            this.loadCurrencies();
        },

        loadCurrencies() {
            this.currencyRepository
                .search(new Criteria(), this.context)
                .then((response) => {
                    this.currencies = response;
                });
        },

        getOptionsForGroup() {
            this.optionsForGroup = Object.values(this.product.configuratorSettings)
                // Filter if option is in active group
                .filter((element) => {
                    if (element.option.groupId === this.activeGroup.id) {
                        this.resetSurcharges(element);
                        return true;
                    }
                    return false;
                })
                // Filter if search term matches option name
                .filter((element) => element.option.name.toLowerCase().includes(this.term.toLowerCase()));
        },

        resetSurcharges(option, force = false) {
            // check if surcharge exists
            if (Array.isArray(option.price) && option.price && option.price.length > 0 && !force) {
                return;
            }

            // set empty surcharge
            this.$set(option, 'price', []);
            this.currenciesList.forEach((currency) => {
                if (!option.price.find(price => price.currencyId === currency.id)) {
                    const newPriceForCurrency = {
                        currencyId: currency.id,
                        gross: 0,
                        linked: true,
                        net: 0
                    };
                    option.price.push(newPriceForCurrency);
                }
            });
        },

        getCurrencyOfOption(option, currencyId) {
            return option.price.find((currency) => currency.currencyId === currencyId);
        }
    }
});