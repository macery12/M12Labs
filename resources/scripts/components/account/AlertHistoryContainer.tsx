import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBell } from '@fortawesome/free-solid-svg-icons';
import AlertHistoryModal from '@account/AlertHistoryModal';

export default () => {
    const [visible, setVisible] = useState(false);

    return (
        <>
            <AlertHistoryModal open={visible} onClose={() => setVisible(false)} />

            <div className={'navigation-link'} onClick={() => setVisible(true)}>
                <FontAwesomeIcon icon={faBell} />
                Alerts
            </div>
        </>
    );
};
