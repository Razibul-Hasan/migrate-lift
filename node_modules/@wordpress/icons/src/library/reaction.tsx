import { Circle, Path, SVG } from '@wordpress/primitives';

export default (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: "none" } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M4.75 12C4.75 7.99593 7.99594 4.75 12 4.75C16.0041 4.75 19.25 7.99594 19.25 12C19.25 16.0041 16.0041 19.25 12 19.25C7.99594 19.25 4.75 16.0041 4.75 12Z" strokeLinejoin="round" />
		<Path d="M9 12.5C9 14.1569 10.3431 15.5 12 15.5C13.6569 15.5 15 14.1569 15 12.5" strokeLinejoin="round" />
		<Circle cx="9.5" cy="10" r="0.5" fill="currentColor" />
		<Circle cx="14.5" cy="10" r="0.5" fill="currentColor" />
	</SVG>
);
