<?php

namespace App\Shared\Http;

/**
 * Catálogo de códigos de error estables de la API (Maestro §7.1 y §25).
 */
enum ErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case HttpError = 'HTTP_ERROR';
    case InternalError = 'INTERNAL_ERROR';

    case TenantNotFound = 'TENANT_NOT_FOUND';
    case TenantInactive = 'TENANT_INACTIVE';
    case TenantRequired = 'TENANT_REQUIRED';
    case CentralOnly = 'CENTRAL_ONLY';

    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case TooManyAttempts = 'TOO_MANY_ATTEMPTS';
    case AccountInactive = 'ACCOUNT_INACTIVE';
    case InvalidResetToken = 'INVALID_RESET_TOKEN';
    case PermissionDenied = 'PERMISSION_DENIED';
    case PrivilegeEscalation = 'PRIVILEGE_ESCALATION';
    case RoleIsSystem = 'ROLE_IS_SYSTEM';
    case RoleInUse = 'ROLE_IN_USE';
    case LastAdministrator = 'LAST_ADMINISTRATOR';

    case GradeScaleInUse = 'GRADE_SCALE_IN_USE';

    case CourseNotPublishable = 'COURSE_NOT_PUBLISHABLE';
    case CourseInvalidState = 'COURSE_INVALID_STATE';

    case QuestionInUse = 'QUESTION_IN_USE';

    case EnrollmentNotActive = 'ENROLLMENT_NOT_ACTIVE';
    case EnrollmentExpired = 'ENROLLMENT_EXPIRED';
    case EnrollmentNotStarted = 'ENROLLMENT_NOT_STARTED';

    case CourseNotForSale = 'COURSE_NOT_FOR_SALE';
    case PriceChanged = 'PRICE_CHANGED';
    case AlreadyEnrolled = 'ALREADY_ENROLLED';
    case OrderAlreadyPending = 'ORDER_ALREADY_PENDING';
    case OrderNotPending = 'ORDER_NOT_PENDING';

    case CertificateNotEligible = 'CERTIFICATE_NOT_ELIGIBLE';
    case CertificateRevoked = 'CERTIFICATE_REVOKED';

    case LeadNotOpen = 'LEAD_NOT_OPEN';
    case QuotationInvalidState = 'QUOTATION_INVALID_STATE';
    case QuotationIncomplete = 'QUOTATION_INCOMPLETE';
    case QuotationExpired = 'QUOTATION_EXPIRED';
    case OrderNotCorporate = 'ORDER_NOT_CORPORATE';
    case LicenseNotAvailable = 'LICENSE_NOT_AVAILABLE';
    case LicenseInUse = 'LICENSE_IN_USE';

    case NoAttemptsLeft = 'NO_ATTEMPTS_LEFT';
    case AttemptClosed = 'ATTEMPT_CLOSED';
    case SubmissionGraded = 'SUBMISSION_GRADED';
    case SurveyAlreadyAnswered = 'SURVEY_ALREADY_ANSWERED';
}
