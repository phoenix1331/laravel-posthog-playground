import { startAnalytics } from './analytics';
import { bindExitSurvey } from './exit-survey';

const posthog = startAnalytics();

bindExitSurvey(document, window.sessionStorage, posthog, window.fetch.bind(window));
