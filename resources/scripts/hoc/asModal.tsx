import { PureComponent } from 'react';
import isEqual from 'react-fast-compare';

import PortaledModal, { ModalProps } from '@/elements/Modal';
import ModalContext, { ModalContextValues } from '@/elements/ModalContext';

export interface AsModalProps {
    visible: boolean;
    onModalDismissed?: () => void;
}

export type SettableModalProps = Omit<ModalProps, 'appear' | 'visible' | 'onDismissed'>;

interface State {
    render: boolean;
    visible: boolean;
    propOverrides: Partial<SettableModalProps>;
}

// eslint-disable-next-line @typescript-eslint/ban-types
function asModal<P extends {}>(
    modalProps?: SettableModalProps | ((props: P) => SettableModalProps),
): (Component: any) => any {
    return function (Component) {
        return class extends PureComponent<P & AsModalProps, State> {
            static displayName = `asModal(${Component.displayName})`;

            constructor(props: P & AsModalProps) {
                super(props);

                this.state = {
                    render: props.visible,
                    visible: props.visible,
                    propOverrides: {},
                };
            }

            get computedModalProps(): Readonly<SettableModalProps & { visible: boolean }> {
                return {
                    ...(typeof modalProps === 'function' ? modalProps(this.props) : modalProps),
                    ...this.state.propOverrides,
                    visible: this.state.visible,
                };
            }

            /**
             * @this {React.PureComponent<P & AsModalProps, State>}
             */
            override componentDidUpdate(prevProps: Readonly<P & AsModalProps>, prevState: Readonly<State>) {
                const wasVisible = prevProps.visible;
                const isVisible = this.props.visible;
                const becameHidden = wasVisible && !isVisible;
                const becameVisible = !wasVisible && isVisible;
                
                if (becameHidden) {
                    this.setState({ visible: false, propOverrides: {} });
                } else if (becameVisible) {
                    this.setState({ render: true, visible: true });
                }
                
                const shouldResetOverrides = !this.state.render && !isEqual(prevState.propOverrides, this.state.propOverrides);
                if (shouldResetOverrides) {
                    this.setState({ propOverrides: {} });
                }
            }

            dismiss = () => this.setState({ visible: false });

            setPropOverrides: ModalContextValues['setPropOverrides'] = value =>
                this.setState(state => ({
                    propOverrides: !value ? {} : typeof value === 'function' ? value(state.propOverrides) : value,
                }));

            /**
             * @this {React.PureComponent<P & AsModalProps, State>}
             */
            override render() {
                if (!this.state.render) return null;

                return (
                    <PortaledModal
                        appear
                        onDismissed={() => {
                            const resetState = { render: false };
                            this.setState(resetState, () => {
                                if (typeof this.props.onModalDismissed === 'function') {
                                    this.props.onModalDismissed();
                                }
                            });
                        }}
                        {...this.computedModalProps}
                    >
                        <ModalContext.Provider
                            value={{
                                dismiss: this.dismiss.bind(this),
                                setPropOverrides: this.setPropOverrides.bind(this),
                            }}
                        >
                            <Component {...this.props} />
                        </ModalContext.Provider>
                    </PortaledModal>
                );
            }
        };
    };
}

export default asModal;
