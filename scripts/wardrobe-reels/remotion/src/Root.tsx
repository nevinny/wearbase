import React from 'react';
import {Composition} from 'remotion';
import {VoicedReel, ReelProps} from './VoicedReel';

const fallback: ReelProps = {total: 1, band: 1360, beats: []};

export const Root: React.FC = () => (
  <Composition
    id="VoicedReel"
    component={VoicedReel}
    fps={30}
    width={1080}
    height={1920}
    durationInFrames={30}
    defaultProps={fallback}
    calculateMetadata={({props}) => ({durationInFrames: Math.round(props.total * 30)})}
  />
);
