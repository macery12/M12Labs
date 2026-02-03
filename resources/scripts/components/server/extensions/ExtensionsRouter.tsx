import { Route, Routes } from 'react-router-dom';
import { NotFound } from '@/elements/ScreenBlock';
import ExtensionsContainer from './ExtensionsContainer';
import PlayerManagerContainer from './PlayerManagerContainer';

export default () => {
    return (
        <Routes>
            <Route path={'/'} element={<ExtensionsContainer />} />
            <Route path={'/minecraft_player_manager'} element={<PlayerManagerContainer />} />
            <Route path={'/*'} element={<NotFound />} />
        </Routes>
    );
};
