( function() {
    const formsData = window.AstraBuilderFormsData || {};
    const endpoint = formsData.endpoint || '';
    const messages = formsData.messages || {};

    const serializeFields = ( form ) => {
        const data = new window.FormData( form );
        const hiddenId = form.querySelector( '[name="_astra_form_id"]' );
        const payload = {
            formId: form.getAttribute( 'data-form-id' ) || ( hiddenId ? hiddenId.value : '' ),
            fields: {},
            submittedAt: 0,
            honeypot: '',
            requirements: [],
        };

        data.forEach( ( value, key ) => {
            if ( '_astra_form_id' === key ) {
                payload.formId = value;
                return;
            }
            if ( '_astra_timestamp' === key ) {
                payload.submittedAt = parseInt( value, 10 );
                return;
            }
            if ( '_astra_requirements' === key ) {
                try {
                    payload.requirements = JSON.parse( value );
                } catch ( e ) {
                    payload.requirements = [];
                }
                return;
            }
            if ( form.dataset.honeypot === key ) {
                payload.honeypot = value;
                return;
            }

            if ( Object.prototype.hasOwnProperty.call( payload.fields, key ) ) {
                const previous = payload.fields[ key ];
                payload.fields[ key ] = Array.isArray( previous ) ? previous.concat( value ) : [ previous, value ];
            } else {
                payload.fields[ key ] = value;
            }
        } );

        return payload;
    };

    const setStatus = ( container, status, message ) => {
        if ( ! container ) {
            return;
        }
        container.textContent = message;
        container.setAttribute( 'data-status', status );
    };

    const initStepper = ( form ) => {
        const steps = Array.from( form.querySelectorAll( '[data-astra-form-step]' ) );
        if ( ! steps.length ) {
            return;
        }
        let activeIndex = 0;

        const updateStepVisibility = () => {
            steps.forEach( ( step, index ) => {
                if ( index === activeIndex ) {
                    step.classList.add( 'is-active' );
                    step.removeAttribute( 'aria-hidden' );
                } else {
                    step.classList.remove( 'is-active' );
                    step.setAttribute( 'aria-hidden', 'true' );
                }
            } );
        };

        const canAdvance = ( nextIndex ) => {
            if ( nextIndex <= activeIndex ) {
                return true;
            }
            const currentStep = steps[ activeIndex ];
            const requiredFields = Array.from( currentStep.querySelectorAll( '[data-required-field="true"]' ) );
            return requiredFields.every( ( field ) => {
                if ( 'checkbox' === field.type ) {
                    return field.checked;
                }
                return field.value && field.value.length > 0;
            } );
        };

        steps.forEach( ( step, index ) => {
            const nextBtn = step.querySelector( '[data-astra-step-next]' );
            const prevBtn = step.querySelector( '[data-astra-step-prev]' );

            if ( nextBtn ) {
                nextBtn.addEventListener( 'click', ( event ) => {
                    event.preventDefault();
                    if ( canAdvance( index + 1 ) && index + 1 < steps.length ) {
                        activeIndex = index + 1;
                        updateStepVisibility();
                    }
                } );
            }

            if ( prevBtn ) {
                prevBtn.addEventListener( 'click', ( event ) => {
                    event.preventDefault();
                    activeIndex = Math.max( 0, index - 1 );
                    updateStepVisibility();
                } );
            }
        } );

        updateStepVisibility();
    };

    const bootForm = ( form ) => {
        const status = form.querySelector( '.astra-builder-form__status' );
        const timestampField = form.querySelector( '[name="_astra_timestamp"]' );
        const successMessage = form.getAttribute( 'data-success-message' ) || messages.success || '';
        const errorMessage = messages.error || '';

        if ( timestampField ) {
            timestampField.value = Date.now().toString();
        }

        initStepper( form );

        form.addEventListener( 'submit', ( event ) => {
            event.preventDefault();
            setStatus( status, 'pending', messages.sending || 'Sending…' );

            const payload = serializeFields( form );
            const targetEndpoint = form.getAttribute( 'data-endpoint' ) || endpoint;

            if ( ! targetEndpoint ) {
                setStatus( status, 'error', errorMessage );
                return;
            }

            window.fetch( targetEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify( payload ),
            } ).then( ( response ) => {
                if ( ! response.ok ) {
                    throw new Error( 'Request failed' );
                }
                return response.json();
            } ).then( () => {
                setStatus( status, 'success', successMessage );
                form.reset();
                if ( timestampField ) {
                    timestampField.value = Date.now().toString();
                }
            } ).catch( () => {
                setStatus( status, 'error', errorMessage );
            } );
        } );
    };

    document.addEventListener( 'DOMContentLoaded', () => {
        const forms = document.querySelectorAll( '.astra-builder-form' );
        forms.forEach( ( form ) => {
            form.setAttribute( 'data-endpoint', form.getAttribute( 'data-endpoint' ) || endpoint );
            if ( ! form.dataset.honeypot && formsData.spam ) {
                form.dataset.honeypot = formsData.spam.honeypotField;
            }
            bootForm( form );
        } );
    } );
} )();
