<?php
/* WIP. Not integrated or approved for installation.
 * Rate changes are atomic; appointment capture must be invoked INSIDE the
 * transaction that inserts that appointment, before the booking commits. */
require_once __DIR__ . '/record_event.php';

function joma_v2_set_rate($store, $therapist_id, $service_id, $rate_rial, $active, $actor)
{
    if (!isset($actor['person_id'], $actor['role']) || $actor['role'] !== 'admin') {
        throw new InvalidArgumentException('Only administrators can set rates');
    }
    joma_v2_positive_id($actor['person_id'], 'Actor');
    joma_v2_positive_id($therapist_id, 'Therapist');
    joma_v2_positive_id($service_id, 'Service');
    joma_v2_rial($rate_rial, true);
    if (!is_bool($active)) throw new InvalidArgumentException('Invalid rate status');
    $store->begin();
    try {
        // Lock a stable subject row first, including when a rate does not exist.
        // The store must verify the therapist role and active service.
        if (!$store->lockRateSubject($therapist_id, $service_id)) {
            throw new InvalidArgumentException('Therapist or service not active');
        }
        $previous = $store->rateForUpdate($therapist_id, $service_id);
        if ($previous !== null && $previous['amount'] === $rate_rial && $previous['active'] === $active) {
            $store->commit();
            return $previous['id'];
        }
        $id = $store->saveRate($therapist_id, $service_id, $rate_rial, $active, $previous);
        $store->appendRateChange($id, $previous, $rate_rial, $active, $actor['person_id']);
        $store->commit();
        return $id;
    } catch (Throwable $ex) {
        $store->rollback();
        throw $ex;
    }
}

/** Internal booking hook. Must only run inside the NEW booking transaction.
 * If no rate is configured, create an explicitly unpriced wallet and return
 * false; never substitute the old session tariff or today's rate later. */
function joma_v2_capture_booking_rate($store, $appointment_id)
{
    joma_v2_positive_id($appointment_id, 'Appointment');
    $appointment = $store->newAppointmentForUpdate($appointment_id);
    if (!$appointment || $appointment['rescheduled_from_id'] !== null) {
        throw new InvalidArgumentException('Expected a new appointment');
    }
    if ($store->walletForAppointment($appointment_id) !== null) {
        throw new InvalidArgumentException('Appointment snapshot already exists');
    }
    $rate = $store->lockRateSubject($appointment['therapist_id'], $appointment['service_id'])
        ? $store->rateForUpdate($appointment['therapist_id'], $appointment['service_id']) : null;
    $amount = ($rate !== null && $rate['active']) ? $rate['amount'] : null;
    $store->insertAppointmentWallet($appointment_id, $appointment['therapist_id'], $appointment['service_id'], $amount);
    return $amount !== null;
}

/** Preserve the old rate only for the same therapist/service. Never substitute
 * a new rate for an old unpriced or historical appointment on rescheduling. */
function joma_v2_capture_rescheduled_rate($store, $appointment_id, $old_id)
{
    joma_v2_positive_id($appointment_id, 'Appointment');
    joma_v2_positive_id($old_id, 'Previous appointment');
    if ($appointment_id === $old_id) throw new InvalidArgumentException('Invalid reschedule');
    $appointment = $store->newAppointmentForUpdate($appointment_id);
    if (!$appointment || $appointment['rescheduled_from_id'] !== $old_id) {
        throw new InvalidArgumentException('Reschedule link does not match');
    }
    if ($store->walletForAppointment($appointment_id) !== null) {
        throw new InvalidArgumentException('Appointment snapshot already exists');
    }
    $old = $store->walletForAppointment($old_id);
    $amount = null;
    if ($old !== null && $old['therapist_id'] === $appointment['therapist_id'] &&
        $old['service_id'] === $appointment['service_id']) {
        $amount = $old['amount'];
    }
    $store->insertAppointmentWallet($appointment_id, $appointment['therapist_id'], $appointment['service_id'], $amount);
    return $amount !== null;
}
