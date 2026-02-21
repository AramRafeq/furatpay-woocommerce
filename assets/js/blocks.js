const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { createElement, useState, useEffect } = wp.element;
const { __ } = wp.i18n;

const PaymentServiceList = ({ services, selectedService, onSelect }) => {
    return createElement('ul', { className: 'furatpay-method-list' },
        services.map(service => 
            createElement('li', { key: service.id, className: 'furatpay-method-item' },
                createElement('label', null,
                    createElement('input', {
                        type: 'radio',
                        name: 'furatpay_service',
                        value: service.id,
                        checked: selectedService === service.id,
                        onChange: () => onSelect(service.id),
                        required: 'required',
                        className: 'furatpay-service-radio'
                    }),
                    service.logo && createElement('img', {
                        src: service.logo,
                        alt: service.name,
                        className: 'furatpay-method-logo'
                    }),
                    createElement('span', { className: 'furatpay-method-name' }, service.name)
                )
            )
        )
    );
};

// Function to show payment overlay
function showPaymentWaitingOverlay() {
        const overlay = document.createElement('div');
    overlay.className = 'furatpay-payment-overlay';
    overlay.innerHTML = `
        <div class="furatpay-payment-status">
            <h2>${furatpayData.i18n.waiting}</h2>
            <p>${furatpayData.i18n.complete_payment}</p>
            <div class="furatpay-spinner"></div>
        </div>
    `;
    document.body.appendChild(overlay);
    document.body.classList.add('furatpay-waiting');
}

// Function to open payment window
function openPaymentWindow(paymentUrl) {
    const paymentWindow = window.open(paymentUrl, '_blank');
    if (!paymentWindow) {
        return false;
    }
    return true;
}

const FuratPayComponent = ({ eventRegistration, emitResponse }) => {
    const [selectedService, setSelectedService] = useState(null);
    const [paymentServices, setPaymentServices] = useState([]);
    const { onPaymentProcessing } = eventRegistration;

    useEffect(() => {
        // Fetch payment services
        fetch(furatpayData.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'furatpay_get_payment_services',
                nonce: furatpayData.nonce
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && Array.isArray(data.data)) {
                setPaymentServices(data.data);
            }
        })
        .catch(error => {
        });
    }, []);

    useEffect(() => {
        const unsubscribe = onPaymentProcessing(() => {
            if (!selectedService) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: __('Please select a payment service.', 'woo_furatpay')
                };
            }

            // Show overlay immediately
            showPaymentWaitingOverlay();

            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        payment_method: 'furatpay',
                        furatpay_service: selectedService.toString()
                    }
                }
            };
        });

        return () => unsubscribe();
    }, [onPaymentProcessing, selectedService]);

    // Handle service selection
    const handleServiceSelect = (serviceId) => {
        setSelectedService(serviceId);
    };

    if (paymentServices.length === 0) {
        return createElement('div', { className: 'furatpay-payment-method-block' },
            createElement('p', null, __('No payment services available.', 'woo_furatpay'))
        );
    }

    return createElement('div', { className: 'furatpay-payment-method-block' },
        createElement(PaymentServiceList, {
            services: paymentServices,
            selectedService: selectedService,
            onSelect: handleServiceSelect
        })
    );
};

const FuratPayLabel = () => {
    return createElement('div', { className: 'furatpay-block-label' },
        createElement('span', null, furatpayData.title),
        furatpayData.icon && createElement('img', {
            src: furatpayData.icon,
            alt: 'FuratPay',
            className: 'furatpay-icon'
        })
    );
};

const paymentMethodConfiguration = {
    name: 'furatpay',
    label: createElement(FuratPayLabel, null),
    content: createElement(FuratPayComponent, null),
    edit: createElement(FuratPayComponent, null),
    canMakePayment: () => true,
    ariaLabel: __('FuratPay payment method', 'woo_furatpay'),
    supports: {
        features: furatpayData.supports || [],
        showSavedPaymentMethods: false,
        showSaveOption: false
    }
};

registerPaymentMethod(paymentMethodConfiguration);