import React, { useState, useEffect } from 'react';
import api from './api';
import Login from './screens/Login';
import Home from './screens/Home';
import ActionPicker from './screens/ActionPicker';
import ClassSelect from './screens/ClassSelect';
import ClassAttendance from './screens/ClassAttendance';
import Chapel from './screens/Chapel';
import CultoSummary from './screens/CultoSummary';

export default function App() {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [screen, setScreen] = useState('home');
    const [selectedCulto, setSelectedCulto] = useState(null);
    const [selectedClase, setSelectedClase] = useState(null);
    const [toast, setToast] = useState(null);

    useEffect(() => {
        api.get('/user')
            .then(res => setUser(res.data))
            .catch(() => setUser(null))
            .finally(() => setLoading(false));
    }, []);

    const showToast = (msg) => {
        setToast(msg);
        setTimeout(() => setToast(null), 2500);
    };

    const navigate = (screenName, data = {}) => {
        if (data.culto) setSelectedCulto(data.culto);
        if (data.clase) setSelectedClase(data.clase);
        setScreen(screenName);
    };

    if (loading) {
        return (
            <div className="app-container" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100vh' }}>
                <div className="spinner" />
            </div>
        );
    }

    if (!user) {
        return <Login onLogin={setUser} />;
    }

    const renderScreen = () => {
        switch (screen) {
            case 'home':
                return <Home user={user} navigate={navigate} onLogout={() => { setUser(null); }} />;
            case 'actions':
                return <ActionPicker culto={selectedCulto} navigate={navigate} />;
            case 'class-select':
                return <ClassSelect culto={selectedCulto} navigate={navigate} />;
            case 'class-attendance':
                return <ClassAttendance culto={selectedCulto} clase={selectedClase} navigate={navigate} showToast={showToast} />;
            case 'chapel':
                return <Chapel culto={selectedCulto} navigate={navigate} showToast={showToast} />;
            case 'summary':
                return <CultoSummary culto={selectedCulto} navigate={navigate} />;
            default:
                return <Home user={user} navigate={navigate} onLogout={() => { setUser(null); }} />;
        }
    };

    return (
        <div className="app-container">
            {renderScreen()}
            {toast && <div className="toast">{toast}</div>}
        </div>
    );
}
