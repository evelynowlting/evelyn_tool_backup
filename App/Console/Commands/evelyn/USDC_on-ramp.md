USDC on-ramp
    * Darren決定用cybavo當水池
    project OwlTing-Wallet-Pro
    circle
        balance check API
        建水池時call一次
            get wire instruction API
        transfer API
    checkout.com
        payment API
        3D secure https://www.checkout.com/docs/payments/authenticate-payments/3d-secure#Frictionless_flow
        webhook
            授權authorize
            請款capture
            入金paid
            爭議chargeback dispute
            option:
                3DS authenticate failed
            ...

            3DS related
            authentication_approved
            authentication_attempted
            authentication_failed
            authentication_started
            authorization_advice_received
            authorization_approved
            authorization_declined

            Dispute
            dispute_accepted
            dispute_arbitration_lost
            dispute_arbitration_sent_to_scheme
            dispute_arbitration_won
            dispute_canceled
            dispute_evidence_acknowledged_by_scheme
            dispute_evidence_required
            dispute_evidence_submitted
            dispute_expired
            dispute_lost
            dispute_received
            dispute_resolved
            dispute_won

            
            payment_approved
            payment_authentication_failed
            payment_authorization_increment_declined
            payment_authorization_incremented
            payment_canceled
            payment_capture_declined
            payment_capture_pending
            payment_captured
            payment_compliance_review
            payment_declined
            payment_expired
            payment_instrument_created
            payment_instrument_error
            payment_instrument_verification_failed
            payment_instrument_verification_passed
            payment_paid
            payment_pending
            payment_refund_declined
            payment_refund_pending
            payment_refunded
            payment_returned
            payment_void_declined
            payment_voided
            payments_disabled
            payments_enabled
    cybavo
        Kai?

nium baas ach
    測試
    Q&A
    email

USDC off-ramp
    US bank is required (FV bank)
