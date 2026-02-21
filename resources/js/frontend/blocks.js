/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME } from './constants';

const PaymentServiceList = ({ services, selectedService, onSelect }) => {
    return (
        <ul className="furatpay-method-list">
            {services.map((service) => (
                <li key={service.id} className="furatpay-method-item">
                    <label>
                        <input
                            type="radio"
                            name="furatpay_service"
                            value={service.id}
                            checked={selectedService === service.id}
                            onChange={() => onSelect(service.id)}
                            required="required"
                            className="furatpay-service-radio"
                        />
                        {service.logo && (
                            <img
                                src={service.logo}
                                alt={service.name}
                                className="furatpay-method-logo"
                            />
                        )}
                        <span className="furatpay-method-name">
                            {service.name}
                        </span>
                    </label>
                </li>
            ))}
        </ul>
    );
};

const FuratPayComponent = ({ eventRegistration, emitResponse, extensions }) => {
    const [selectedService, setSelectedService] = useState(null);
    const [paymentServices, setPaymentServices] = useState([]);
    const { onPaymentSetup } = eventRegistration;

    // Fetch payment services only once on mount
    useEffect(() => {
        let isMounted = true;

        const fetchServices = async () => {
            try {
                const response = await fetch(furatpayData.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'furatpay_get_payment_services',
                        nonce: furatpayData.nonce,
                    }),
                });

                if (!response.ok) {
                    throw new Error('Failed to fetch payment services');
                }

                const data = await response.json();

                if (isMounted && data.success && Array.isArray(data.data)) {
                    setPaymentServices(data.data);
                    // Auto-select first service to ensure payment method is active
                    if (data.data.length > 0 && !selectedService) {
                        const firstServiceId = data.data[0].id;
                        const firstServiceName = data.data[0].name;
                        setSelectedService(firstServiceId);

                        // Store in sessionStorage
                        try {
                            sessionStorage.setItem('furatpay_service_id', firstServiceId.toString());
                            sessionStorage.setItem('furatpay_service_name', firstServiceName);
                                                    } catch (e) {
                                                    }
                    }
                }
            } catch (error) {
                            }
        };

        fetchServices();

        return () => {
            isMounted = false;
        };
    }, []);

    // Register payment setup callback
    useEffect(() => {
        const unsubscribe = onPaymentSetup(() => {
            if (!selectedService) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: __('Please select a payment service.', 'woo_furatpay'),
                };
            }

            // Find the selected service details
            const service = paymentServices.find(s => s.id === selectedService);
            if (!service) {
                                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: __('Invalid payment service selected.', 'woo_furatpay'),
                };
            }

            const paymentData = [
                { key: 'furatpay_service', value: selectedService.toString() },
                { key: 'furatpay_service_name', value: service.name }
            ];

            
            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: paymentData,
                },
                paymentMethodData: paymentData,
            };
        });

        return () => unsubscribe();
    }, [onPaymentSetup, selectedService, paymentServices, emitResponse.responseTypes]);

    if (paymentServices.length === 0) {
        return (
            <div className="furatpay-payment-method-block">
                <p>{__('No payment services available.', 'woo_furatpay')}</p>
            </div>
        );
    }

    // Handler to update selected service and store in sessionStorage
    const handleServiceSelect = (serviceId) => {
        setSelectedService(serviceId);

        // Find the service details
        const service = paymentServices.find(s => s.id === serviceId);

        if (service) {
            // Store in sessionStorage so PHP can access it
            try {
                sessionStorage.setItem('furatpay_service_id', serviceId.toString());
                sessionStorage.setItem('furatpay_service_name', service.name);
            } catch (e) {
                            }
        }
    };

    return (
        <div className="furatpay-payment-method-block">
            <PaymentServiceList
                services={paymentServices}
                selectedService={selectedService}
                onSelect={handleServiceSelect}
            />
        </div>
    );
};

const FuratPayLabel = () => {
    return (
        <div className="furatpay-block-label">
            <span>{furatpayData.title}</span>
            {furatpayData.icon && (
                <img
                    src={furatpayData.icon}
                    alt="FuratPay"
                    className="furatpay-icon"
                />
            )}
        </div>
    );
};

const options = {
    name: PAYMENT_METHOD_NAME,
    label: <FuratPayLabel />,
    content: <FuratPayComponent />,
    edit: <FuratPayComponent />,
    canMakePayment: () => {
        return true;
    },
    ariaLabel: __('FuratPay payment method', 'woo_furatpay'),
    supports: {
        features: furatpayData.supports || ['products'],
        showSavedPaymentMethods: false,
        showSaveOption: false,
    },
};

// Use the global wc object
const { registerPaymentMethod } = wc.wcBlocksRegistry;
registerPaymentMethod(options);

// Intercept fetch requests to inject extension data
(function() {
    const originalFetch = window.fetch;
    window.fetch = function(...args) {
        const [url, config] = args;

        // Check if this is the checkout endpoint
        if (url && typeof url === 'string' && url.includes('/wc/store/v1/checkout')) {

            // Get the stored service data
            const serviceId = sessionStorage.getItem('furatpay_service_id');
            const serviceName = sessionStorage.getItem('furatpay_service_name');

            if (serviceId && config && config.body) {
                try {
                    // Parse the request body
                    const body = JSON.parse(config.body);

                    // Add our extension data
                    if (!body.extensions) {
                        body.extensions = {};
                    }

                    body.extensions.furatpay = {
                        furatpay_service: serviceId,
                        furatpay_service_name: serviceName || ''
                    };

                    // Update the request body
                    config.body = JSON.stringify(body);

                } catch (e) {
                }
            } else {
            }
        }

        // Call the original fetch
        return originalFetch.apply(this, args);
    };

})();

// Critical: Ensure payment method is auto-selected when it's the only one
if (typeof wp !== 'undefined' && wp.data && wp.data.select) {
    // Wait for DOM to be ready
    wp.domReady(() => {

        // Try to get the checkout store
        try {
            const checkoutStore = wp.data.select('wc/store/checkout');
            if (checkoutStore) {
                const availableMethods = checkoutStore.getAvailablePaymentMethods();

                // If furatpay is available, try to select it
                if (availableMethods && availableMethods.includes(PAYMENT_METHOD_NAME)) {
                    const dispatch = wp.data.dispatch('wc/store/payment');
                    if (dispatch && dispatch.setActivePaymentMethod) {
                        dispatch.setActivePaymentMethod(PAYMENT_METHOD_NAME);
                    }
                }
            }
        } catch (e) {
        }
    });
} 