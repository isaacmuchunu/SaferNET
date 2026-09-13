import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { CircleCheckIcon, CircleSlashIcon, Undo2Icon } from 'lucide-react';
import { Button, DetailRow, Field, Textarea, firstError } from '../../components/Primitives';
import { ConfirmDialog, Drawer } from '../../components/Overlays';
import { useReviewInstitution } from '../../lib/queries';
import { INSTITUTION_TYPES, OWNERSHIP_TYPES } from '../../lib/domain';
import { formatDate, formatNumber } from '../../lib/format';
import { ApiError } from '../../lib/api';
import { showToast, toastError } from '../../lib/toast';

const DECISIONS = [
    {
        value: 'approve',
        label: 'Approve registration',
        helper: 'Clears the school to begin onboarding and device deployment.',
        icon: CircleCheckIcon,
        tone: 'brand',
    },
    {
        value: 'return',
        label: 'Return for correction',
        helper: 'Sends the submission back to the originating officer as a draft.',
        icon: Undo2Icon,
        tone: 'warning',
    },
    {
        value: 'reject',
        label: 'Reject registration',
        helper: 'Declines the school. The decision is written to the audit log.',
        icon: CircleSlashIcon,
        tone: 'danger',
    },
];

const SELECTED = {
    brand: 'border-brand bg-brand-soft',
    warning: 'border-warning bg-warning-soft',
    danger: 'border-danger bg-danger-soft',
};

const ICON_TONE = { brand: 'text-brand', warning: 'text-warning-strong', danger: 'text-danger' };

/**
 * County Director review of a pending registration. Notes are mandatory for
 * any decision other than approval, mirroring the server-side rule.
 */
export function ReviewDrawer({ institution, onClose, onReviewed }) {
    const review = useReviewInstitution();
    const [decision, setDecision] = useState('approve');
    const [notes, setNotes] = useState('');
    const [confirming, setConfirming] = useState(false);

    useEffect(() => {
        if (institution) {
            setDecision('approve');
            setNotes('');
            setConfirming(false);
            review.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [institution?.id]);

    const errors = review.error instanceof ApiError ? review.error.errors : {};
    const chosen = DECISIONS.find((item) => item.value === decision);

    async function submit() {
        try {
            await review.mutateAsync({ id: institution.id, decision, notes: notes || null });
            showToast(
                decision === 'approve'
                    ? `${institution.name} approved`
                    : decision === 'return'
                      ? `${institution.name} returned for correction`
                      : `${institution.name} registration rejected`,
                { description: 'The decision was written to the county audit log.' },
            );
            setConfirming(false);
            onReviewed?.(institution);
            onClose();
        } catch (error) {
            setConfirming(false);

            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The review could not be recorded');
            }
        }
    }

    return (
        <>
            <Drawer
                open={Boolean(institution)}
                onClose={onClose}
                title={institution?.name ?? ''}
                subtitle={institution ? `${institution.subcounty?.name ?? 'Kiambu'} · NEMIS ${institution.nemis_code}` : undefined}
                footer={
                    <>
                        <Button className="flex-1" onClick={onClose} disabled={review.isPending}>
                            Cancel
                        </Button>
                        <Button
                            variant={decision === 'reject' ? 'danger' : 'primary'}
                            className="flex-1"
                            loading={review.isPending}
                            onClick={() => setConfirming(true)}
                        >
                            Submit decision
                        </Button>
                    </>
                }
            >
                {institution && (
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <DetailRow label="Institution type">{INSTITUTION_TYPES[institution.institution_type]}</DetailRow>
                            <DetailRow label="Ownership">{OWNERSHIP_TYPES[institution.ownership]}</DetailRow>
                            <DetailRow label="Sub-county">{institution.subcounty?.name}</DetailRow>
                            <DetailRow label="Physical location">{institution.physical_location}</DetailRow>
                            <DetailRow label="Head of institution">{institution.hoi?.name}</DetailRow>
                            <DetailRow label="Declared learners">{formatNumber(institution.learner_population)}</DetailRow>
                            <DetailRow label="Declared devices">{formatNumber(institution.computing_devices_count)}</DetailRow>
                            <DetailRow label="Submitted">{formatDate(institution.submitted_at, { withTime: true })}</DetailRow>
                        </div>

                        <fieldset>
                            <legend className="mb-2 text-xs font-semibold">Decision</legend>
                            <div className="space-y-2">
                                {DECISIONS.map(({ value, label, helper, icon: Icon, tone }) => (
                                    <label
                                        key={value}
                                        className={clsx(
                                            'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors duration-150',
                                            'has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-brand',
                                            decision === value ? SELECTED[tone] : 'border-border hover:bg-surface-muted',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="decision"
                                            value={value}
                                            checked={decision === value}
                                            onChange={() => setDecision(value)}
                                            className="sr-only"
                                        />
                                        <Icon size={16} className={clsx('mt-0.5 shrink-0', decision === value ? ICON_TONE[tone] : 'text-text-muted')} />
                                        <span>
                                            <span className="block text-[13px] font-semibold">{label}</span>
                                            <span className="mt-0.5 block text-[11px] leading-4 text-text-secondary">{helper}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>

                        <Field
                            label="Officer notes"
                            required={decision !== 'approve'}
                            hint={
                                decision === 'approve'
                                    ? 'Optional for approvals. Notes are retained in the audit log.'
                                    : 'Required. Explain what the originating officer must correct.'
                            }
                            error={firstError(errors, 'notes') ?? firstError(errors, 'decision')}
                        >
                            <Textarea
                                value={notes}
                                maxLength={2000}
                                onChange={(event) => setNotes(event.target.value)}
                                placeholder="Set out the basis for this decision."
                            />
                        </Field>

                        <p className="rounded-lg bg-info-soft p-3 text-xs leading-4 text-info">
                            Approving activates onboarding: officer accounts, learner import, laboratory creation and
                            protection deployment.
                        </p>
                    </div>
                )}
            </Drawer>

            <ConfirmDialog
                open={confirming}
                tone={decision === 'reject' ? 'danger' : 'brand'}
                title={`${chosen?.label}?`}
                description={
                    institution
                        ? `${institution.name} will be ${decision === 'approve' ? 'approved and moved into onboarding' : decision === 'return' ? 'returned to the submitting officer' : 'declined'}. Your name is recorded against this decision.`
                        : ''
                }
                confirmLabel={chosen?.label ?? 'Confirm'}
                loading={review.isPending}
                onCancel={() => setConfirming(false)}
                onConfirm={submit}
            />
        </>
    );
}
