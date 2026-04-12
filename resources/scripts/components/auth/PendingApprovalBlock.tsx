function PendingApprovalBlock() {
    return (
        <div
            style={{
                color: 'white',
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                minHeight: '60vh',
                gap: '12px',
                textAlign: 'center',
                padding: '24px',
            }}
        >
            <svg
                xmlns='http://www.w3.org/2000/svg'
                style={{ width: 48, height: 48, color: '#a78bfa' }}
                fill='none'
                viewBox='0 0 24 24'
                stroke='currentColor'
            >
                <path
                    strokeLinecap='round'
                    strokeLinejoin='round'
                    strokeWidth={1.5}
                    d='M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'
                />
            </svg>
            <h1 style={{ fontSize: '1.5rem', fontWeight: 700 }}>Account Pending Approval</h1>
            <p style={{ color: '#9ca3af', maxWidth: 420 }}>
                Your account is awaiting review by an administrator. You will receive access once your registration has
                been approved. Please check back later.
            </p>
        </div>
    );
}

export default PendingApprovalBlock;
